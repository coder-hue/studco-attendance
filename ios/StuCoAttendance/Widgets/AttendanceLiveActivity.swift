import ActivityKit
import SwiftUI
import WidgetKit

struct AttendanceLiveActivity: Widget {
    private let royalBlue = Color(red: 0.12, green: 0.38, blue: 1.0)

    var body: some WidgetConfiguration {
        ActivityConfiguration(for:AttendanceActivityAttributes.self) { context in
            HStack(spacing: 16) {
                VStack(alignment: .leading, spacing: 5) {
                    HStack(spacing: 7) {
                        Circle().fill(royalBlue).frame(width: 8, height: 8)
                        Text(context.state.isOpen ? "CHECK-IN OPEN" : "CHECK-IN CLOSED")
                            .font(.caption.bold()).foregroundStyle(royalBlue)
                    }
                    Text(context.attributes.eventTitle)
                        .font(.headline).foregroundStyle(.white).lineLimit(1)
                    Text("\(context.state.present) of \(context.state.total) submitted")
                        .font(.caption).foregroundStyle(.white.opacity(0.68))
                }
                Spacer()
                Link(destination: qrURL(context.attributes.eventID)) {
                    VStack(spacing: 3) {
                        Image(systemName: "qrcode").font(.title3.bold())
                        Text("QR").font(.caption2.bold())
                    }
                    .foregroundStyle(.white)
                    .frame(width: 54, height: 54)
                    .background(royalBlue, in: RoundedRectangle(cornerRadius: 14))
                }
            }
            .padding()
            .activityBackgroundTint(.black)
            .activitySystemActionForegroundColor(royalBlue)
        } dynamicIsland:{ context in
            DynamicIsland {
                DynamicIslandExpandedRegion(.leading) {
                    Label(context.state.isOpen ? "Open" : "Closed", systemImage: context.state.isOpen ? "circle.fill" : "circle")
                        .font(.caption.bold()).foregroundStyle(royalBlue)
                }
                DynamicIslandExpandedRegion(.trailing) {
                    Text("\(context.state.present) submitted").font(.caption.bold()).foregroundStyle(royalBlue)
                }
                DynamicIslandExpandedRegion(.center) {
                    Text(context.attributes.eventTitle).font(.headline).lineLimit(1)
                }
                DynamicIslandExpandedRegion(.bottom) {
                    HStack {
                        Text("\(context.state.present) of \(context.state.total) checked in").font(.caption).foregroundStyle(.secondary)
                        Spacer()
                        Link(destination: qrURL(context.attributes.eventID)) {
                            Label("Open QR", systemImage: "qrcode")
                                .font(.caption.bold()).padding(.horizontal, 12).padding(.vertical, 7)
                                .foregroundStyle(.white).background(royalBlue, in: Capsule())
                        }
                    }
                }
            } compactLeading:{
                Image(systemName: context.state.isOpen ? "circle.fill" : "circle").foregroundStyle(royalBlue)
            } compactTrailing:{
                Text("\(context.state.present)").font(.caption.bold()).foregroundStyle(royalBlue)
            } minimal:{
                Text("\(context.state.present)").font(.caption.bold()).foregroundStyle(royalBlue)
            }
            .keylineTint(royalBlue)
        }
    }

    private func qrURL(_ eventID: Int) -> URL {
        URL(string: "stucoadmin://event/\(eventID)/qr")!
    }
}
